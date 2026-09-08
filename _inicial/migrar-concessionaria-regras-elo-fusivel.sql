-- =============================================================================
-- Trael — SGT
-- Migração: exige_elo_fusivel em concessionaria_regras — antes hardcoded em
-- pages/qualidade/paint-check.php (['Equatorial', 'Amazonas Energia']), agora
-- configurável pelo admin (pages/admin/concessionaria-regras.php), mesmo
-- padrão de local_codigo_adicional / local_potencia.
-- =============================================================================
-- Idempotente: pode ser rodado mais de uma vez sem erro (mesmo já aplicada).
--
-- USO:
--   mysql -u root trael_db < _inicial/migrar-concessionaria-regras-elo-fusivel.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS _migrar_concreg_elo_fusivel_patch;
DELIMITER $$
CREATE PROCEDURE _migrar_concreg_elo_fusivel_patch()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'concessionaria_regras' AND COLUMN_NAME = 'exige_elo_fusivel'
    ) THEN
        ALTER TABLE concessionaria_regras
            ADD COLUMN exige_elo_fusivel TINYINT(1) NOT NULL DEFAULT 0 AFTER local_potencia;
    END IF;
END$$
DELIMITER ;
CALL _migrar_concreg_elo_fusivel_patch();
DROP PROCEDURE _migrar_concreg_elo_fusivel_patch;

-- Migra o hardcode existente: só Equatorial (com tabela pra validar de verdade,
-- pintura.md §3.1.B) e Amazonas Energia (exige a evidência, sem tabela ainda).
UPDATE concessionaria_regras
SET exige_elo_fusivel = 1
WHERE nome_grupo IN ('Equatorial', 'Amazonas Energia') AND deleted_at IS NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Fim da migração. Confira com:
--   SELECT nome_grupo, exige_elo_fusivel FROM concessionaria_regras;
-- =============================================================================
