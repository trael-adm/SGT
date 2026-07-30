-- =============================================================================
-- Trael — SGT
-- Migração: id_lote em retrabalhos — agrupa reprovas registradas na mesma
-- triagem (mesmo envio do formulário "Adicionar nova reprova"), para que
-- contem como 1 ocorrência nas Repetências (e não 1 por código de reprova).
-- =============================================================================
-- Idempotente: pode ser rodado mais de uma vez sem erro (mesmo já aplicada).
--
-- USO:
--   mysql -u root trael_db < _inicial/migrar-retrabalho-lote.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS _migrar_retrabalho_lote_patch;
DELIMITER $$
CREATE PROCEDURE _migrar_retrabalho_lote_patch()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retrabalhos' AND COLUMN_NAME = 'id_lote'
    ) THEN
        -- NULL = registro avulso (conta como sua própria ocorrência via COALESCE(id_lote, id)).
        ALTER TABLE retrabalhos ADD COLUMN id_lote INT NULL AFTER id_reprova;
        ALTER TABLE retrabalhos ADD KEY idx_retra_lote (id_lote);
    END IF;
END$$
DELIMITER ;
CALL _migrar_retrabalho_lote_patch();
DROP PROCEDURE _migrar_retrabalho_lote_patch;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Fim da migração. Confira com:
--   DESCRIBE retrabalhos;   -- deve mostrar id_lote logo após id_reprova
-- =============================================================================
