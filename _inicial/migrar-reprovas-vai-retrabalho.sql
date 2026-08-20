-- ============================================================================
-- SGT — Migração: Coluna 'vai_retrabalho' na tabela 'reprovas'
-- ============================================================================
-- Adiciona o campo 'vai_retrabalho' (1 = Sim [padrão], 0 = Não)
-- Se 1 (Sim): fluxo normal de retrabalho.
-- Se 0 (Não): a peça não vai para o retrabalho, voltando para a tela de Retornos
--             do mesmo setor (LAB ou IQF) para correção interna.
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'reprovas' 
      AND COLUMN_NAME = 'vai_retrabalho'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE reprovas ADD COLUMN vai_retrabalho TINYINT(1) NOT NULL DEFAULT 1 AFTER local;',
    'SELECT "Coluna vai_retrabalho ja existe" AS status;'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
