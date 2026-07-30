-- =============================================================================
-- Trael — SGT
-- Migração: Triagem do retrabalho (chegada, causa da reprova, setores de
-- destino e anexos) + tabela de anexos
-- =============================================================================
-- Idempotente: pode ser rodado mais de uma vez sem erro (mesmo já aplicada).
--
-- USO:
--   mysql -u root trael_db < _inicial/migrar-retrabalho-triagem.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS _migrar_retrabalho_triagem_patch;
DELIMITER $$
CREATE PROCEDURE _migrar_retrabalho_triagem_patch()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retrabalhos' AND COLUMN_NAME = 'data_chegada'
    ) THEN
        ALTER TABLE retrabalhos ADD COLUMN data_chegada DATE NULL AFTER data_reprova;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retrabalhos' AND COLUMN_NAME = 'causa_reprova'
    ) THEN
        ALTER TABLE retrabalhos ADD COLUMN causa_reprova TEXT NULL AFTER data_chegada;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retrabalhos' AND COLUMN_NAME = 'setores_destino'
    ) THEN
        -- Lista de slugs separados por vírgula (ex.: "bobinagem_at,solda,pintura"). Ver
        -- includes/helpers.php::retrabalhoSetoresTriagem() para a lista permitida.
        ALTER TABLE retrabalhos ADD COLUMN setores_destino VARCHAR(255) NULL AFTER causa_reprova;
    END IF;
END$$
DELIMITER ;
CALL _migrar_retrabalho_triagem_patch();
DROP PROCEDURE _migrar_retrabalho_triagem_patch;

-- ─── Anexos da triagem (fotos/PDF anexados ao registrar uma reprova) ───────────
CREATE TABLE IF NOT EXISTS retrabalho_anexos (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    id_retrabalho INT          NOT NULL,
    nome_arquivo  VARCHAR(255) NOT NULL,   -- nome salvo em disco (único, gerado)
    nome_original VARCHAR(255) NOT NULL,   -- nome do arquivo enviado pelo usuário
    tamanho_bytes INT          NOT NULL DEFAULT 0,
    id_criador    INT          NULL,
    created_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at    TIMESTAMP    NULL DEFAULT NULL,
    CONSTRAINT fk_retra_anexo_retrabalho FOREIGN KEY (id_retrabalho) REFERENCES retrabalhos(id),
    CONSTRAINT fk_retra_anexo_criador    FOREIGN KEY (id_criador)    REFERENCES usuarios(id),
    KEY idx_retra_anexo_retrabalho (id_retrabalho)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Fim da migração. Confira com:
--   DESCRIBE retrabalhos;         -- deve mostrar data_chegada, causa_reprova, setores_destino
--   SHOW TABLES LIKE 'retrabalho_anexos';
-- =============================================================================
