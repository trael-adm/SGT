-- =============================================================================
-- Trael — SGT
-- Migração: remover Setor e Data de fim do módulo de retrabalho
-- =============================================================================
-- Remove as colunas `setor` e `data_fim` de `retrabalhos` (não são mais usadas).
-- Dropar a coluna `setor` remove automaticamente o índice idx_retra_setor.
--
-- USO:
--   mysql -u root trael_db < _inicial/migrar-retrabalho-remove-setor-datafim.sql
-- =============================================================================

USE trael_db;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE retrabalhos DROP COLUMN setor;
ALTER TABLE retrabalhos DROP COLUMN data_fim;

SET FOREIGN_KEY_CHECKS = 1;
