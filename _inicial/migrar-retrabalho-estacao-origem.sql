-- =============================================================================
-- Trael — SGT
-- Migração: adicionar coluna `estacao` em `retrabalhos` para registrar a origem real da reprovação
-- =============================================================================
-- Adiciona a coluna `estacao` em `retrabalhos` para identificar se a reprovação
-- teve origem no Laboratório (LAB), na Inspeção Final (IQF) ou no Retrabalho (RET),
-- desacoplando a origem do transformador do catálogo geral de reprovas (`rep.local`).
--
-- USO:
--   mysql -u root trael_db < _inicial/migrar-retrabalho-estacao-origem.sql
-- =============================================================================

USE trael_db;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE retrabalhos ADD COLUMN IF NOT EXISTS estacao VARCHAR(20) DEFAULT NULL AFTER id_reprova;

-- Popula registros existentes
UPDATE retrabalhos r
JOIN producao_etapas pe ON pe.ns_transformador = r.ns_transformador AND pe.id_projeto = r.id_projeto
SET r.estacao = pe.estacao
WHERE r.estacao IS NULL AND pe.estacao IN ('LAB', 'IQF', 'GER');

UPDATE retrabalhos r
JOIN reprovas rep ON rep.id = r.id_reprova
SET r.estacao = rep.local
WHERE r.estacao IS NULL AND rep.local IN ('LAB', 'IQF');

UPDATE retrabalhos r
SET r.estacao = 'LAB'
WHERE r.estacao IS NULL OR r.estacao = 'GER';

SET FOREIGN_KEY_CHECKS = 1;
