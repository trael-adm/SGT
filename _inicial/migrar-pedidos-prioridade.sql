-- =============================================================================
-- Trael — SGT
-- Migração: Prioridade do Pedido. Controlada numa página independente (ver
-- pages/pedidos/prioridade.php). Todo pedido nasce "neutro" até alguém mudar.
-- =============================================================================
-- Idempotente: pode ser rodado mais de uma vez sem erro (mesmo já aplicada, e
-- mesmo vindo da escala antiga de 5 níveis com vermelho/laranja/amarelo/
-- verde/azul — este script converte para a nova escala automaticamente).
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
        ALTER TABLE pedidos ADD COLUMN prioridade ENUM('emergente','urgente','importante','neutro') NOT NULL DEFAULT 'neutro' AFTER numero;
    ELSE
        -- Coluna já existe: pode estar na escala antiga (5 níveis, aceitava NULL) —
        -- amplia o ENUM temporariamente para caber os dois vocabulários, remapeia
        -- os valores e só então estreita para a escala nova (sempre preenchida).
        ALTER TABLE pedidos MODIFY COLUMN prioridade
            ENUM('vermelho','laranja','amarelo','verde','azul','emergente','urgente','importante','neutro') NULL;

        -- ELSE só é alcançado quando prioridade IS NULL (todo valor antigo tem WHEN
        -- próprio) — cobre exatamente os dois casos do WHERE abaixo.
        UPDATE pedidos SET prioridade = CASE prioridade
            WHEN 'vermelho' THEN 'emergente'
            WHEN 'laranja'  THEN 'urgente'
            WHEN 'amarelo'  THEN 'importante'
            WHEN 'verde'    THEN 'neutro'
            WHEN 'azul'     THEN 'neutro'
            ELSE 'neutro'
        END
        WHERE prioridade IS NULL OR prioridade IN ('vermelho','laranja','amarelo','verde','azul');

        ALTER TABLE pedidos MODIFY COLUMN prioridade
            ENUM('emergente','urgente','importante','neutro') NOT NULL DEFAULT 'neutro';
    END IF;
END$$
DELIMITER ;
CALL _migrar_pedidos_prioridade_patch();
DROP PROCEDURE _migrar_pedidos_prioridade_patch;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Fim da migração. Confira com:
--   DESCRIBE pedidos;                                -- prioridade NOT NULL DEFAULT 'neutro'
--   SELECT prioridade, COUNT(*) FROM pedidos GROUP BY prioridade;
-- =============================================================================
