-- ==============================================================================
-- MIGRAÇÃO: colunas de Perfis/Setores para a tela de Admin (Gestão de Usuários)
-- ------------------------------------------------------------------------------
-- Equivalente, para MySQL Community "de verdade" (sem `ADD COLUMN IF NOT EXISTS`,
-- que é sintaxe MariaDB), da parte de `perfis`/`setores`/`usuario_acessos` do
-- migrar-railway-sprint5.sql — que roda em produção/Railway mas nunca tinha
-- rodado neste MySQL local (e não rodaria: dá erro de sintaxe aqui).
--
-- NÃO inclui as duas linhas de UPDATE reprovas nem a limpeza de FK de
-- admin_setores do sprint5 — isso é dado de negócio de outra feature
-- (Revitalizações/GER), fora do escopo deste fix.
--
-- Idempotente (usa information_schema pra só alterar o que ainda falta).
-- ==============================================================================

DELIMITER //
CREATE PROCEDURE _tmp_add_col_if_missing(
    IN p_tabela VARCHAR(64), IN p_coluna VARCHAR(64), IN p_ddl VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabela AND COLUMN_NAME = p_coluna
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', p_tabela, ' ADD COLUMN ', p_ddl);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- 1. Colunas base de Perfis (cod/grupo/descricao/perms — nunca viraram .sql)
CALL _tmp_add_col_if_missing('perfis', 'cod', 'cod VARCHAR(20) NULL AFTER id');
CALL _tmp_add_col_if_missing('perfis', 'grupo', 'grupo VARCHAR(10) NULL AFTER nome');
CALL _tmp_add_col_if_missing('perfis', 'descricao', 'descricao TEXT NULL AFTER grupo');
CALL _tmp_add_col_if_missing('perfis', 'perms', 'perms JSON NULL AFTER descricao');

-- 2. Colunas do sprint5 em Perfis
CALL _tmp_add_col_if_missing('perfis', 'status', "status VARCHAR(20) DEFAULT 'ativo' AFTER perms");
CALL _tmp_add_col_if_missing('perfis', 'sistema', 'sistema TINYINT(1) DEFAULT 0 AFTER status');
CALL _tmp_add_col_if_missing('perfis', 'created_at', 'created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER sistema');
CALL _tmp_add_col_if_missing('perfis', 'deleted_at', 'deleted_at TIMESTAMP NULL DEFAULT NULL');

UPDATE perfis SET cod = UPPER(SUBSTRING(nome, 1, 3)) WHERE cod IS NULL OR cod = '';
UPDATE perfis SET grupo = cod WHERE grupo IS NULL OR grupo = '';
UPDATE perfis SET perms = '{}' WHERE perms IS NULL;
UPDATE perfis SET status = 'ativo' WHERE status IS NULL OR status = '';

-- 3. Colunas do sprint5 em Setores
CALL _tmp_add_col_if_missing('setores', 'cod', 'cod VARCHAR(10) NULL AFTER id');
CALL _tmp_add_col_if_missing('setores', 'resp', 'resp VARCHAR(100) NULL AFTER nome');
CALL _tmp_add_col_if_missing('setores', 'cc', 'cc VARCHAR(20) NULL AFTER resp');
CALL _tmp_add_col_if_missing('setores', 'turnos', 'turnos JSON NULL AFTER cc');
CALL _tmp_add_col_if_missing('setores', 'perfil', 'perfil VARCHAR(20) NULL AFTER turnos');
CALL _tmp_add_col_if_missing('setores', 'status', "status VARCHAR(20) DEFAULT 'ativo' AFTER perfil");
CALL _tmp_add_col_if_missing('setores', 'created_at', 'created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
CALL _tmp_add_col_if_missing('setores', 'deleted_at', 'deleted_at TIMESTAMP NULL DEFAULT NULL');

UPDATE setores SET cod = UPPER(SUBSTRING(nome, 1, 3)) WHERE cod IS NULL OR cod = '';
UPDATE setores SET status = 'ativo' WHERE status IS NULL OR status = '';

-- 4. Tabela de exceções de permissão por usuário (sprint5, item 4)
CREATE TABLE IF NOT EXISTS usuario_acessos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    tela VARCHAR(50) NOT NULL,
    nivel VARCHAR(20) NOT NULL DEFAULT 'total',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
    UNIQUE KEY uk_usuario_tela (id_usuario, tela)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. admin_setores: tabela legada (substituída por `setores`, ver
-- _inicial/migrar-fix-fk-usuarios-setor.sql), mas api/admin-v2-acao.php ainda
-- faz um cleanup defensivo nela ao excluir um perfil — sem ela essa ação quebra.
CREATE TABLE IF NOT EXISTS admin_setores (
    id INT NOT NULL AUTO_INCREMENT,
    cod VARCHAR(10) NOT NULL,
    nome VARCHAR(100) NOT NULL,
    resp VARCHAR(100) DEFAULT NULL,
    cc VARCHAR(20) DEFAULT NULL,
    turnos JSON NOT NULL,
    id_perfil INT DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ativo',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY cod (cod),
    KEY id_perfil (id_perfil),
    CONSTRAINT admin_setores_ibfk_1 FOREIGN KEY (id_perfil) REFERENCES perfis (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE _tmp_add_col_if_missing;
