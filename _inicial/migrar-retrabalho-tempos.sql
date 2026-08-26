-- =============================================================================
-- Trael — SGT
-- Migração: Catálogo de Tempo Médio de Retrabalho por Reprova (Minutos)
-- =============================================================================
-- Idempotente: pode ser rodado mais de uma vez sem erro (mesmo já aplicada).
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─── 1. Coluna de tempo padrão em minutos na tabela reprovas ─────────────────
DROP PROCEDURE IF EXISTS _migrar_reprovas_tempo_padrao;
DELIMITER $$
CREATE PROCEDURE _migrar_reprovas_tempo_padrao()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'reprovas' 
          AND COLUMN_NAME = 'tempo_padrao_minutos'
    ) THEN
        ALTER TABLE reprovas 
        ADD COLUMN tempo_padrao_minutos INT NOT NULL DEFAULT 60 AFTER local;
    END IF;
END$$
DELIMITER ;
CALL _migrar_reprovas_tempo_padrao();
DROP PROCEDURE IF EXISTS _migrar_reprovas_tempo_padrao;

-- ─── 2. Valores padrão coerentes por família de reprova ─────────────────────
-- Reprovas mecânicas / vazamento / solda / pintura / elétricas
UPDATE reprovas SET tempo_padrao_minutos = 90  WHERE familia IN ('VAZAMENTO', 'FURO NA SOLDA', 'PINTURA') AND tempo_padrao_minutos = 60;
UPDATE reprovas SET tempo_padrao_minutos = 120 WHERE familia IN ('REPROVA ELÉTRICA') AND tempo_padrao_minutos = 60;
UPDATE reprovas SET tempo_padrao_minutos = 45  WHERE familia IN ('TERMINAL BT', 'TERMINAL', 'ISOLADOR', 'ALÇA DE FIXAÇÃO PA') AND tempo_padrao_minutos = 60;
UPDATE reprovas SET tempo_padrao_minutos = 30  WHERE familia IN ('SERIGRAFIA') AND tempo_padrao_minutos = 60;
UPDATE reprovas SET tempo_padrao_minutos = 60  WHERE familia IN ('PROJETO', 'REVITALIZAÇÃO', 'COMUTADOR', 'BT') AND tempo_padrao_minutos = 60;

SET FOREIGN_KEY_CHECKS = 1;
