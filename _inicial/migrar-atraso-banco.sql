-- Migração: Criação das tabelas para persistência nativa do Atraso da Distribuição
-- Data: 2026-08-27

CREATE TABLE IF NOT EXISTS `atraso_distribuicao_registros` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `data_extracao` DATE NOT NULL,
    `data_programada` DATE NOT NULL,
    `cd_referencia` VARCHAR(50) NULL,
    `ds_produto` VARCHAR(255) NULL,
    `qtd_item` INT NOT NULL DEFAULT 1,
    `quantidade` INT NOT NULL DEFAULT 1,
    `qtd_produzida` INT NOT NULL DEFAULT 0,
    `qtd_a_produzir` INT NOT NULL DEFAULT 0,
    `cliente_nome` VARCHAR(255) NULL,
    `cliente_apelido` VARCHAR(100) NULL,
    `cd_pedido` INT NULL,
    `dt_pedido` DATETIME NULL,
    `dt_limite_entrega` DATE NULL,
    `potencia_kva` DECIMAL(10,2) NULL,
    `fases` VARCHAR(20) NULL,
    `classe_tensao` VARCHAR(50) NULL,
    `tipo_nucleo` VARCHAR(50) NULL,
    `tipo_construtivo` VARCHAR(50) NULL,
    `linha` VARCHAR(30) NOT NULL, -- 'Convencional', 'JC-TRIF', 'Monofásico', 'Outros'
    `seq_plano` INT NULL,
    `uf` VARCHAR(10) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_data_extracao` (`data_extracao`),
    INDEX `idx_data_prog` (`data_programada`),
    INDEX `idx_linha` (`linha`),
    INDEX `idx_extracao_linha` (`data_extracao`, `linha`),
    INDEX `idx_pedido_ref` (`cd_pedido`, `cd_referencia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `atraso_metas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `mes` VARCHAR(7) NOT NULL, -- '2026-08'
    `linha` VARCHAR(30) NOT NULL, -- 'Convencional', 'JC-TRIF', 'Monofásico'
    `meta_dias` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_mes_linha` (`mes`, `linha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
