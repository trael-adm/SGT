-- =============================================================================
-- Trael — SGT
-- Migração: local_potencia em concessionaria_regras — onde a serigrafia de
-- potência (kVA) é exigida, além do tanque (padrão universal em todas as
-- concessionárias, ver pintura.md). Só a Energisa exige também na tampa
-- (ETU-109.1 Item 11.4.1, Desenho 10: "Potência Nominal" na tampa superior).
-- Mesmo padrão de local_codigo_adicional (SET multivalorado).
-- =============================================================================
-- Idempotente: pode ser rodado mais de uma vez sem erro (mesmo já aplicada).
--
-- USO:
--   mysql -u root trael_db < _inicial/migrar-concessionaria-regras-potencia.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS _migrar_concreg_potencia_patch;
DELIMITER $$
CREATE PROCEDURE _migrar_concreg_potencia_patch()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'concessionaria_regras' AND COLUMN_NAME = 'local_potencia'
    ) THEN
        -- Default 'tanque' preserva o comportamento atual (hardcoded em
        -- pages/qualidade/paint-check.php) pra todas as regras já cadastradas.
        ALTER TABLE concessionaria_regras
            ADD COLUMN local_potencia SET('tampa','tanque') NOT NULL DEFAULT 'tanque' AFTER local_codigo_adicional;
    END IF;
END$$
DELIMITER ;
CALL _migrar_concreg_potencia_patch();
DROP PROCEDURE _migrar_concreg_potencia_patch;

-- Energisa: única concessionária com potência também na tampa (pintura.md §3.3.C).
UPDATE concessionaria_regras
SET local_potencia = 'tampa,tanque'
WHERE nome_grupo = 'Energisa' AND deleted_at IS NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Fim da migração. Confira com:
--   SELECT nome_grupo, local_potencia FROM concessionaria_regras;
-- =============================================================================
