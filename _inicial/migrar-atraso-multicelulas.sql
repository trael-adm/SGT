-- ==============================================================================
-- Migração: Suporte a Múltiplas Células Pendentes por Registro de Atraso
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `atraso_distribuicao_celulas` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `registro_id` BIGINT NOT NULL,
    `data_extracao` DATE NOT NULL,
    `celula_sigla` VARCHAR(10) NOT NULL,
    `celula_nome` VARCHAR(60) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_extracao_celula` (`data_extracao`, `celula_nome`),
    INDEX `idx_registro` (`registro_id`),
    INDEX `idx_extracao_sigla` (`data_extracao`, `celula_sigla`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Coluna de conferência direta em atraso_distribuicao_registros
SET @colExists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'atraso_distribuicao_registros' 
      AND COLUMN_NAME = 'setores_pendentes'
);

SET @sql = IF(@colExists = 0, 
    'ALTER TABLE `atraso_distribuicao_registros` ADD COLUMN `setores_pendentes` VARCHAR(255) NULL AFTER `setor_real`', 
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
