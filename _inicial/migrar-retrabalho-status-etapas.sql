-- =============================================================================
-- Trael — SGT
-- Migração: fluxo de status por etapas no retrabalho
-- =============================================================================
-- - Renomeia os status: aberto→agu_abertura, em_andamento→agu_causa_raiz, concluido→finalizado
-- - Adiciona `data_finalizacao` (DATE) e `causa_raiz` (TEXT)
-- O status passa a ser derivado automaticamente dos dados (data_finalizacao / causa_raiz).
--
-- USO (banco existente) — ATENÇÃO ao encoding: rode via `source`, não via pipe do PowerShell:
--   mysql -u root --default-character-set=utf8mb4 -e "source C:/laragon/www/SGT/_inicial/migrar-retrabalho-status-etapas.sql"
-- =============================================================================

USE trael_db;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. ENUM superset temporário (antigos + novos) para permitir a migração dos dados
ALTER TABLE retrabalhos
    MODIFY status ENUM('aberto','em_andamento','concluido','agu_abertura','agu_causa_raiz','finalizado')
    NOT NULL DEFAULT 'aberto';

-- 2. Migrar valores antigos → novos (preserva registros existentes)
UPDATE retrabalhos SET status = CASE status
    WHEN 'aberto'       THEN 'agu_abertura'
    WHEN 'em_andamento' THEN 'agu_causa_raiz'
    WHEN 'concluido'    THEN 'finalizado'
    ELSE status
END;

-- 3. ENUM final (só os novos valores)
ALTER TABLE retrabalhos
    MODIFY status ENUM('agu_abertura','agu_causa_raiz','finalizado')
    NOT NULL DEFAULT 'agu_abertura';

-- 4. Novos campos
ALTER TABLE retrabalhos ADD COLUMN data_finalizacao DATE NULL AFTER data_inicio;
ALTER TABLE retrabalhos ADD COLUMN causa_raiz       TEXT NULL AFTER observacoes;

-- 5. Remover o campo Operador (não é mais usado)
ALTER TABLE retrabalhos DROP COLUMN operador;

SET FOREIGN_KEY_CHECKS = 1;

-- Conferir:
--   SHOW COLUMNS FROM retrabalhos;   -- status ENUM novo + data_finalizacao + causa_raiz
--   SELECT id, ns_transformador, status FROM retrabalhos;  -- NS 858585 deve estar 'agu_abertura'
