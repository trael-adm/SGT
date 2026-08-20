-- =============================================================================
-- Trael — SGT
-- Migração: Adiciona a coluna sequencia na tabela pedidos.
-- =============================================================================
-- Idempotente: pode ser rodado mais de uma vez sem erro.
--
-- USO:
--   mysql -u root trael_db < _inicial/migrar-pedidos-sequencia.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS _migrar_pedidos_sequencia;
DELIMITER $$
CREATE PROCEDURE _migrar_pedidos_sequencia()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'sequencia'
    ) THEN
        ALTER TABLE pedidos ADD COLUMN sequencia INT NOT NULL DEFAULT 0 AFTER prioridade;
    END IF;
END$$
DELIMITER ;
CALL _migrar_pedidos_sequencia();
DROP PROCEDURE _migrar_pedidos_sequencia;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Fim da migração.
-- =============================================================================
