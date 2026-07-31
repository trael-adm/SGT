-- =============================================================================
-- Trael — SGT
-- Migração: Prioridade do Pedido — mesma escala de cores do protocolo de
-- triagem de saúde (Manchester), sem o tempo-alvo de atendimento. Controlada
-- numa página independente (ver pages/pedidos/prioridade.php).
-- =============================================================================
-- Idempotente: pode ser rodado mais de uma vez sem erro (mesmo já aplicada).
--
-- USO:
--   mysql -u root trael_db < _inicial/migrar-pedidos-prioridade.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS _migrar_pedidos_prioridade_patch;
DELIMITER $$
CREATE PROCEDURE _migrar_pedidos_prioridade_patch()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'prioridade'
    ) THEN
        -- Ver includes/helpers.php::pedidoPrioridades() para rótulo/cor de cada nível.
        ALTER TABLE pedidos ADD COLUMN prioridade ENUM('vermelho','laranja','amarelo','verde','azul') NULL AFTER numero;
    END IF;
END$$
DELIMITER ;
CALL _migrar_pedidos_prioridade_patch();
DROP PROCEDURE _migrar_pedidos_prioridade_patch;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Fim da migração. Confira com:
--   DESCRIBE pedidos;   -- deve mostrar a coluna prioridade logo após numero
-- =============================================================================
