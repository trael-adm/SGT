-- =============================================================================
-- Trael — SGT
-- Migração: Gestão de Custos e Parâmetros de Retrabalho
-- =============================================================================
-- Idempotente: pode ser rodado mais de uma vez sem erro (mesmo já aplicada).
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─── 1. Coluna de custo unitário no catálogo de materiais ────────────────────
DROP PROCEDURE IF EXISTS _migrar_retrabalho_materiais_custo;
DELIMITER $$
CREATE PROCEDURE _migrar_retrabalho_materiais_custo()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'retrabalho_materiais_catalogo' 
          AND COLUMN_NAME = 'custo_unitario'
    ) THEN
        ALTER TABLE retrabalho_materiais_catalogo 
        ADD COLUMN custo_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER unidade;
    END IF;
END$$
DELIMITER ;
CALL _migrar_retrabalho_materiais_custo();
DROP PROCEDURE IF EXISTS _migrar_retrabalho_materiais_custo;

-- ─── 2. Tabela de configurações globais de retrabalho ────────────────────────
CREATE TABLE IF NOT EXISTS retrabalho_configuracoes (
    chave       VARCHAR(50)  NOT NULL PRIMARY KEY,
    valor       VARCHAR(100) NOT NULL,
    descricao   VARCHAR(200) NULL,
    updated_at  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valores padrão iniciais
INSERT IGNORE INTO retrabalho_configuracoes (chave, valor, descricao) VALUES
    ('custo_hora_homem', '45.00', 'Custo médio da hora de trabalho para retrabalho (R$/h)'),
    ('horas_trabalho_dia', '8.80', 'Horas úteis de expediente padrão por dia');

-- Atualiza custos unitários estimados padrão nos materiais conhecidos caso estejam 0
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 45.00 WHERE id = 1 AND custo_unitario = 0;
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 15.50 WHERE id = 2 AND custo_unitario = 0;
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 22.00 WHERE id = 3 AND custo_unitario = 0;
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 35.00 WHERE id = 4 AND custo_unitario = 0;
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 12.00 WHERE id = 5 AND custo_unitario = 0;
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 18.00 WHERE id = 6 AND custo_unitario = 0;
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 28.00 WHERE id = 7 AND custo_unitario = 0;
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 85.00 WHERE id = 8 AND custo_unitario = 0;
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 14.00 WHERE id = 9 AND custo_unitario = 0;
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 8.50  WHERE id = 10 AND custo_unitario = 0;
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 120.00 WHERE id = 11 AND custo_unitario = 0;
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 16.00 WHERE id = 12 AND custo_unitario = 0;
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 6.00  WHERE id = 13 AND custo_unitario = 0;
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 450.00 WHERE id = 14 AND custo_unitario = 0;
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 25.00 WHERE id = 15 AND custo_unitario = 0;
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 380.00 WHERE id = 16 AND custo_unitario = 0;
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 32.00 WHERE id = 17 AND custo_unitario = 0;
UPDATE retrabalho_materiais_catalogo SET custo_unitario = 9.80  WHERE id = 18 AND custo_unitario = 0;

SET FOREIGN_KEY_CHECKS = 1;
