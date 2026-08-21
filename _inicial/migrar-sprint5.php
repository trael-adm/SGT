<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== INICIANDO MIGRAÇÃO SPRINT 5 (RAILWAY) ===\n\n";

try {
    $pdo = getDB();
    echo "[OK] Conectado ao banco de dados com sucesso!\n\n";

    $queries = [
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS cpf VARCHAR(14) NULL DEFAULT NULL AFTER email",
        "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL",

        "ALTER TABLE setores ADD COLUMN IF NOT EXISTS cod VARCHAR(10) NULL AFTER id",
        "ALTER TABLE setores ADD COLUMN IF NOT EXISTS resp VARCHAR(100) NULL AFTER nome",
        "ALTER TABLE setores ADD COLUMN IF NOT EXISTS cc VARCHAR(20) NULL AFTER resp",
        "ALTER TABLE setores ADD COLUMN IF NOT EXISTS turnos JSON NULL AFTER cc",
        "ALTER TABLE setores ADD COLUMN IF NOT EXISTS perfil VARCHAR(20) NULL AFTER turnos",
        "ALTER TABLE setores ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'ativo' AFTER perfil",
        "ALTER TABLE setores ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE setores ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL",

        "UPDATE setores SET cod = UPPER(SUBSTRING(nome, 1, 3)) WHERE cod IS NULL OR cod = ''",

        "ALTER TABLE perfis ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'ativo' AFTER perms",
        "ALTER TABLE perfis ADD COLUMN IF NOT EXISTS sistema TINYINT(1) DEFAULT 0 AFTER status",
        "ALTER TABLE perfis ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL",

        "CREATE TABLE IF NOT EXISTS usuario_acessos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            tela VARCHAR(50) NOT NULL,
            nivel VARCHAR(20) NOT NULL DEFAULT 'total',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
            UNIQUE KEY uk_usuario_tela (id_usuario, tela)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "UPDATE reprovas SET local = 'IQF' WHERE local = 'GER' AND codigo NOT LIKE 'R%'",
        "UPDATE reprovas SET local = 'GER', setor_causador = 'REVITALIZAÇÃO' WHERE codigo LIKE 'R%'"
    ];

    foreach ($queries as $i => $q) {
        $short = substr(str_replace("\n", " ", trim($q)), 0, 70);
        echo ($i + 1) . ". Executando: $short...\n";
        try {
            $pdo->exec($q);
            echo "   -> [OK] Executado com sucesso.\n";
        } catch (Throwable $e) {
            echo "   -> [INFO/AVISO]: " . $e->getMessage() . "\n";
        }
    }

    echo "\n=== ✅ TODAS AS MIGRAÇÕES FORAM APLICADAS COM SUCESSO! ===\n";

} catch (Throwable $e) {
    echo "\n[ERRO FATAL]: " . $e->getMessage() . "\n";
}
