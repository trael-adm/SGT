-- =============================================================================
-- Trael — SGT
-- Migração: campo "Correção" por reprova (ex.: "Feito nova isolação") — ao lado
-- da Causa Raiz na Relação de Retrabalhos (ver pages/retrabalho/detalhe.php).
-- =============================================================================
-- Idempotente: pode ser rodado mais de uma vez sem erro (mesmo já aplicada).
--
-- USO:
--   mysql -u root trael_db < _inicial/migrar-retrabalho-correcao.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS _migrar_retrabalho_correcao_patch;
DELIMITER $$
CREATE PROCEDURE _migrar_retrabalho_correcao_patch()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retrabalhos' AND COLUMN_NAME = 'correcao'
    ) THEN
        ALTER TABLE retrabalhos ADD COLUMN correcao TEXT NULL AFTER causa_raiz;
    END IF;
END$$
DELIMITER ;
CALL _migrar_retrabalho_correcao_patch();
DROP PROCEDURE _migrar_retrabalho_correcao_patch;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Fim da migração. Confira com:
--   DESCRIBE retrabalhos;   -- deve mostrar `correcao` logo após `causa_raiz`
-- =============================================================================
