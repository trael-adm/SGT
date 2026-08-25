-- ==============================================================================
-- MIGRAÇÃO CONSOLIDADA: SPRINT 5 (Central Admin, Usuários, Setores, Perfis & GER)
-- Idempotente (Pode ser executado múltiplas vezes com segurança)
-- ==============================================================================

-- 1. CPF e Soft Delete em Usuários
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS cpf VARCHAR(14) NULL DEFAULT NULL AFTER email;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL;

-- 2. Colunas estendidas em Setores
ALTER TABLE setores ADD COLUMN IF NOT EXISTS cod VARCHAR(10) NULL AFTER id;
ALTER TABLE setores ADD COLUMN IF NOT EXISTS resp VARCHAR(100) NULL AFTER nome;
ALTER TABLE setores ADD COLUMN IF NOT EXISTS cc VARCHAR(20) NULL AFTER resp;
ALTER TABLE setores ADD COLUMN IF NOT EXISTS turnos JSON NULL AFTER cc;
ALTER TABLE setores ADD COLUMN IF NOT EXISTS perfil VARCHAR(20) NULL AFTER turnos;
ALTER TABLE setores ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'ativo' AFTER perfil;
ALTER TABLE setores ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE setores ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL;

UPDATE setores SET cod = UPPER(SUBSTRING(nome, 1, 3)) WHERE cod IS NULL OR cod = '';

-- 3. Colunas estendidas em Perfis
ALTER TABLE perfis ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'ativo' AFTER perms;
ALTER TABLE perfis ADD COLUMN IF NOT EXISTS sistema TINYINT(1) DEFAULT 0 AFTER status;
ALTER TABLE perfis ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL;

-- 4. Tabela de Permissões Individuais por Usuário
CREATE TABLE IF NOT EXISTS usuario_acessos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    tela VARCHAR(50) NOT NULL,
    nivel VARCHAR(20) NOT NULL DEFAULT 'total',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
    UNIQUE KEY uk_usuario_tela (id_usuario, tela)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE usuario_acessos ADD COLUMN IF NOT EXISTS tela VARCHAR(50) NOT NULL DEFAULT '' AFTER id_usuario;
ALTER TABLE usuario_acessos ADD COLUMN IF NOT EXISTS nivel VARCHAR(20) NOT NULL DEFAULT 'total' AFTER tela;

-- 5. Restrição de GER para Revitalizações
UPDATE reprovas SET local = 'IQF' WHERE local = 'GER' AND codigo NOT LIKE 'R%';
UPDATE reprovas SET local = 'GER', setor_causador = 'REVITALIZAÇÃO' WHERE codigo LIKE 'R%';

-- 6. Remoção de FKs legadas impeditivas
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_setores' AND CONSTRAINT_NAME = 'admin_setores_ibfk_1');
SET @sql = IF(@fk_exists > 0, 'ALTER TABLE admin_setores DROP FOREIGN KEY admin_setores_ibfk_1', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

