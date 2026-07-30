-- =============================================================================
-- Trael — SGT
-- Migração: novo status 'aguardando_retorno' em producao_etapas — usado quando
-- a Triagem do Retrabalho marca "Laboratório" como próximo setor, sinalizando
-- que o transformador está a caminho de volta ao Laboratório (ver
-- api/retrabalho-acao.php::case 'registrar' e pages/producao/retornos.php).
-- =============================================================================
-- Idempotente: pode ser rodado mais de uma vez sem erro (mesmo já aplicada).
--
-- USO:
--   mysql -u root trael_db < _inicial/migrar-retrabalho-retorno.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS _migrar_retrabalho_retorno_patch;
DELIMITER $$
CREATE PROCEDURE _migrar_retrabalho_retorno_patch()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'producao_etapas' AND COLUMN_NAME = 'status'
          AND COLUMN_TYPE LIKE '%aguardando_retorno%'
    ) THEN
        ALTER TABLE producao_etapas
            MODIFY COLUMN status ENUM('em_andamento','aguardando_retorno','finalizado') NOT NULL DEFAULT 'em_andamento';
    END IF;
END$$
DELIMITER ;
CALL _migrar_retrabalho_retorno_patch();
DROP PROCEDURE _migrar_retrabalho_retorno_patch;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Fim da migração. Confira com:
--   SHOW COLUMNS FROM producao_etapas LIKE 'status';   -- deve incluir 'aguardando_retorno' no ENUM
-- =============================================================================
