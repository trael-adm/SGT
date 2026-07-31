-- =============================================================================
-- Trael — SGT
-- Migração: novo status "aprovado" para o retorno ao Laboratório
-- =============================================================================
-- Na tela "Retornos ao Laboratório", o botão Aprovado marca o retrabalho como
-- concluído com sucesso na reinspeção (sem precisar de Causa Raiz) — status
-- distinto de "finalizado" (que representa a causa raiz já documentada).
-- Aparece na nova "Relação do Histórico" junto com os finalizados.
--
-- Idempotente: MODIFY COLUMN pode ser rodado mais de uma vez sem erro.
--
-- USO:
--   mysql -u root trael_db < _inicial/migrar-retrabalho-aprovado.sql
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE retrabalhos
    MODIFY status ENUM('agu_abertura','agu_causa_raiz','finalizado','aprovado')
    NOT NULL DEFAULT 'agu_abertura';

-- =============================================================================
-- Fim da migração. Confira com:
--   SHOW COLUMNS FROM retrabalhos LIKE 'status';
-- =============================================================================
