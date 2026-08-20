-- =============================================================================
-- Trael — SGT
-- Migração: Adiciona suporte ao Preço Médio unitário (R$) no catálogo de itens
-- (itens_catalogo) e no registro de materiais utilizados (retrabalho_material_uso).
-- =============================================================================
SET NAMES utf8mb4;

-- 1. Coluna preco_medio na tabela itens_catalogo
DROP PROCEDURE IF EXISTS _migrar_itens_catalogo_preco;
DELIMITER $$
CREATE PROCEDURE _migrar_itens_catalogo_preco()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'itens_catalogo' AND COLUMN_NAME = 'preco_medio'
    ) THEN
        ALTER TABLE itens_catalogo ADD COLUMN preco_medio DECIMAL(14,4) NULL DEFAULT NULL AFTER unidade;
    END IF;
END $$
DELIMITER ;
CALL _migrar_itens_catalogo_preco();
DROP PROCEDURE IF EXISTS _migrar_itens_catalogo_preco;

-- 2. Coluna preco_medio na tabela retrabalho_material_uso
DROP PROCEDURE IF EXISTS _migrar_retra_mat_uso_preco;
DELIMITER $$
CREATE PROCEDURE _migrar_retra_mat_uso_preco()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retrabalho_material_uso' AND COLUMN_NAME = 'preco_medio'
    ) THEN
        ALTER TABLE retrabalho_material_uso ADD COLUMN preco_medio DECIMAL(14,4) NULL DEFAULT NULL AFTER quantidade;
    END IF;
END $$
DELIMITER ;
CALL _migrar_retra_mat_uso_preco();
DROP PROCEDURE IF EXISTS _migrar_retra_mat_uso_preco;
