<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== INICIANDO MIGRAÇÃO NATIVA SPRINT 5 (RAILWAY) ===\n\n";

try {
    $pdo = getDB();
    echo "[OK] Conectado ao banco de dados com sucesso!\n\n";

    // Helper para adicionar coluna de forma 100% compatível com MySQL 8/9
    function addColumnIfNotExists(PDO $pdo, string $table, string $column, string $definition): void {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = ? 
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        $exists = (int)$stmt->fetchColumn() > 0;

        if (!$exists) {
            echo "  + Adicionando coluna `$column` na tabela `$table`...";
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            echo " [OK]\n";
        } else {
            echo "  . Coluna `$column` já existe na tabela `$table`.\n";
        }
    }

    // 1. Tabela usuarios
    echo "1. Verificando tabela `usuarios`:\n";
    addColumnIfNotExists($pdo, 'usuarios', 'cpf', 'VARCHAR(14) NULL DEFAULT NULL AFTER email');
    addColumnIfNotExists($pdo, 'usuarios', 'deleted_at', 'TIMESTAMP NULL DEFAULT NULL');

    // 2. Tabela setores
    echo "\n2. Verificando tabela `setores`:\n";
    addColumnIfNotExists($pdo, 'setores', 'cod', 'VARCHAR(10) NULL AFTER id');
    addColumnIfNotExists($pdo, 'setores', 'resp', 'VARCHAR(100) NULL AFTER nome');
    addColumnIfNotExists($pdo, 'setores', 'cc', 'VARCHAR(20) NULL AFTER resp');
    addColumnIfNotExists($pdo, 'setores', 'turnos', 'JSON NULL AFTER cc');
    addColumnIfNotExists($pdo, 'setores', 'perfil', 'VARCHAR(20) NULL AFTER turnos');
    addColumnIfNotExists($pdo, 'setores', 'status', "VARCHAR(20) DEFAULT 'ativo' AFTER perfil");
    addColumnIfNotExists($pdo, 'setores', 'created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
    addColumnIfNotExists($pdo, 'setores', 'deleted_at', 'TIMESTAMP NULL DEFAULT NULL');

    $pdo->exec("UPDATE setores SET cod = UPPER(SUBSTRING(nome, 1, 3)) WHERE cod IS NULL OR cod = ''");
    echo "  -> Siglas dos setores atualizadas.\n";

    // 3. Tabela perfis
    echo "\n3. Verificando tabela `perfis`:\n";
    addColumnIfNotExists($pdo, 'perfis', 'status', "VARCHAR(20) DEFAULT 'ativo' AFTER perms");
    addColumnIfNotExists($pdo, 'perfis', 'sistema', 'TINYINT(1) DEFAULT 0 AFTER status');
    addColumnIfNotExists($pdo, 'perfis', 'deleted_at', 'TIMESTAMP NULL DEFAULT NULL');

    // 4. Tabela usuario_acessos
    echo "\n4. Verificando tabela `usuario_acessos`:\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuario_acessos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        tela VARCHAR(50) NOT NULL,
        nivel VARCHAR(20) NOT NULL DEFAULT 'total',
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
        UNIQUE KEY uk_usuario_tela (id_usuario, tela)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    addColumnIfNotExists($pdo, 'usuario_acessos', 'tela', "VARCHAR(50) NOT NULL DEFAULT '' AFTER id_usuario");
    addColumnIfNotExists($pdo, 'usuario_acessos', 'nivel', "VARCHAR(20) NOT NULL DEFAULT 'total' AFTER tela");
    echo "  [OK] Tabela usuario_acessos verificada/criada.\n";

    // 5. Ajustes de Reprovas GER / Revitalização
    echo "\n5. Verificando catálogo de Reprovas:\n";
    $pdo->exec("UPDATE reprovas SET local = 'IQF' WHERE local = 'GER' AND codigo NOT LIKE 'R%'");
    $pdo->exec("UPDATE reprovas SET local = 'GER', setor_causador = 'REVITALIZAÇÃO' WHERE codigo LIKE 'R%'");
    echo "  [OK] Reprovas atualizadas para Revitalização.\n";

    echo "\n=======================================================\n";
    echo "🎉 SUCESSO: TODAS AS ALTERAÇÕES FORAM APLICADAS NO RAILWAY!\n";
    echo "=======================================================\n";

} catch (Throwable $e) {
    echo "\n[ERRO FATAL]: " . $e->getMessage() . "\n";
}
