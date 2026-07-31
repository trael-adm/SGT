-- =============================================================================
-- Trael — SGT
-- Correção: ns_ativo (coluna gerada de producao_etapas) não considerava
-- deleted_at — uma linha "em_andamento" soft-deletada (ex.: Reprovar retorno ao
-- Laboratório, ver api/retrabalho-acao.php::case 'reprovar_retorno', que só
-- marca deleted_at sem alterar status) continuava contando pro índice único
-- uq_prod_etapas_ns_ativo, bloqueando pra sempre um novo registro do mesmo N°
-- de série com "Este transformador já está em andamento em outra estação."
-- mesmo a etapa antiga já estando excluída (some das telas, mas trava o índice).
-- =============================================================================
-- Idempotente: pode ser rodado mais de uma vez sem erro (mesmo já aplicada).
--
-- USO:
--   mysql -u root trael_db < _inicial/migrar-producao-etapas-ns-ativo.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS _migrar_producao_etapas_ns_ativo_patch;
DELIMITER $$
CREATE PROCEDURE _migrar_producao_etapas_ns_ativo_patch()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'producao_etapas' AND COLUMN_NAME = 'ns_ativo'
          AND GENERATION_EXPRESSION LIKE '%deleted_at%'
    ) THEN
        ALTER TABLE producao_etapas
            MODIFY COLUMN ns_ativo VARCHAR(60) GENERATED ALWAYS AS (
                CASE WHEN `status` = 'em_andamento' AND deleted_at IS NULL THEN ns_transformador ELSE NULL END
            ) STORED;
    END IF;
END$$
DELIMITER ;
CALL _migrar_producao_etapas_ns_ativo_patch();
DROP PROCEDURE _migrar_producao_etapas_ns_ativo_patch;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Fim da migração. Confira com:
--   SELECT id, ns_transformador, status, deleted_at, ns_ativo FROM producao_etapas
--   WHERE deleted_at IS NOT NULL AND status = 'em_andamento';  -- ns_ativo deve vir NULL em todas
-- =============================================================================
