-- =============================================================================
-- SOMA — Cronoanálise Industrial (Trael PCP)
-- Script de Migração e Estrutura Inicial do Módulo SOMA
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. Empresas / Unidades Fabris
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `soma_empresas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cod` VARCHAR(30) NOT NULL,
  `nome` VARCHAR(100) NOT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `id_criador` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_soma_empresas_cod` (`cod`),
  KEY `idx_soma_empresas_ativo` (`ativo`),
  KEY `idx_soma_empresas_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_soma_empresas_criador` FOREIGN KEY (`id_criador`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `soma_empresas` (`id`, `cod`, `nome`, `ativo`) VALUES
  (1, 'TRAEL-MT', 'Trael Transformadores — Matriz (Cuiabá-MT)', 1),
  (2, 'TRAEL-PA', 'Trael Transformadores — Filial (Ananindeua-PA)', 1),
  (3, 'TRAEL-SP', 'Trael Transformadores — Filial (São Paulo-SP)', 1)
ON DUPLICATE KEY UPDATE `nome` = VALUES(`nome`), `ativo` = VALUES(`ativo`);

-- ------------------------------------------------------------
-- 2. Setores Fabris
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `soma_setores` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cod` VARCHAR(20) NOT NULL,
  `descricao` VARCHAR(100) NOT NULL,
  `meta` DECIMAL(5,1) NOT NULL DEFAULT 80.0,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `id_criador` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_soma_setores_cod` (`cod`),
  KEY `idx_soma_setores_ativo` (`ativo`),
  KEY `idx_soma_setores_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_soma_setores_criador` FOREIGN KEY (`id_criador`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `soma_setores` (`id`, `cod`, `descricao`, `meta`, `ativo`) VALUES
  (1, 'BOBIN', 'Bobinagem MT/BT', 85.0, 1),
  (2, 'MONTAG', 'Montagem de Parte Ativa', 80.0, 1),
  (3, 'FECHAM', 'Fechamento e Tanqueamento', 80.0, 1),
  (4, 'CALD', 'Caldeiraria e Serralheria', 75.0, 1),
  (5, 'PINT', 'Pintura e Acabamento', 80.0, 1),
  (6, 'ENSAIOS', 'Laboratório e Ensaios Elétricos', 90.0, 1)
ON DUPLICATE KEY UPDATE `descricao` = VALUES(`descricao`), `meta` = VALUES(`meta`), `ativo` = VALUES(`ativo`);

-- ------------------------------------------------------------
-- 3. Operadores
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `soma_operadores` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cod` VARCHAR(20) NOT NULL,
  `nome` VARCHAR(100) NOT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `id_criador` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_soma_operadores_cod` (`cod`),
  KEY `idx_soma_operadores_ativo` (`ativo`),
  KEY `idx_soma_operadores_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_soma_operadores_criador` FOREIGN KEY (`id_criador`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `soma_operadores` (`id`, `cod`, `nome`, `ativo`) VALUES
  (1, 'OP-001', 'Carlos Silva — Bobinagem', 1),
  (2, 'OP-002', 'Marcos Oliveira — Montagem', 1),
  (3, 'OP-003', 'José Roberto — Fechamento', 1),
  (4, 'OP-004', 'Antônio Souza — Caldeiraria', 1)
ON DUPLICATE KEY UPDATE `nome` = VALUES(`nome`), `ativo` = VALUES(`ativo`);

-- ------------------------------------------------------------
-- 4. Máquinas e Ativos Fabris
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `soma_maquinas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cod` VARCHAR(30) NOT NULL,
  `nome` VARCHAR(100) NOT NULL,
  `patrimonio` VARCHAR(50) DEFAULT NULL,
  `descricao_completa` VARCHAR(255) DEFAULT NULL,
  `setor_local` VARCHAR(100) DEFAULT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `id_criador` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_soma_maquinas_cod` (`cod`),
  KEY `idx_soma_maquinas_ativo` (`ativo`),
  KEY `idx_soma_maquinas_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_soma_maquinas_criador` FOREIGN KEY (`id_criador`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `soma_maquinas` (`id`, `cod`, `nome`, `patrimonio`, `descricao_completa`, `setor_local`, `ativo`) VALUES
  (1, 'BOB-01', 'Bobinadeira MT Automática 01', 'PAT-10452', 'Bobinadeira de média tensão com contador eletrônico', 'Bobinagem MT/BT', 1),
  (2, 'BOB-02', 'Bobinadeira BT Manual 02', 'PAT-10453', 'Bobinadeira para baixa tensão manual com fixador', 'Bobinagem MT/BT', 1),
  (3, 'PREN-01', 'Prensa Hidráulica 50T', 'PAT-10880', 'Prensa de compressão do núcleo magnético', 'Montagem de Parte Ativa', 1),
  (4, 'PONTE-01', 'Ponte Rolante 10 Toneladas', 'PAT-10112', 'Ponte rolante do galpão principal de montagem', 'Montagem de Parte Ativa', 1),
  (5, 'ESTUFA-01', 'Estufa de Secagem a Vácuo', 'PAT-10991', 'Estufa para desumidificação da parte ativa', 'Fechamento e Tanqueamento', 1)
ON DUPLICATE KEY UPDATE `nome` = VALUES(`nome`), `patrimonio` = VALUES(`patrimonio`), `setor_local` = VALUES(`setor_local`), `ativo` = VALUES(`ativo`);

-- ------------------------------------------------------------
-- 5. Motivos de Parada
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `soma_paradas_motivos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cod` VARCHAR(20) NOT NULL,
  `descricao` VARCHAR(150) NOT NULL,
  `tipo` ENUM('PROG','NAO_PROG') NOT NULL DEFAULT 'NAO_PROG',
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `id_criador` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_soma_paradas_motivos_cod` (`cod`),
  KEY `idx_soma_paradas_motivos_tipo` (`tipo`),
  KEY `idx_soma_paradas_motivos_ativo` (`ativo`),
  KEY `idx_soma_paradas_motivos_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_soma_paradas_motivos_criador` FOREIGN KEY (`id_criador`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `soma_paradas_motivos` (`id`, `cod`, `descricao`, `tipo`, `ativo`) VALUES
  (1, 'MANUT-PREV', 'Manutenção Preventiva Programada', 'PROG', 1),
  (2, 'REUNIAO-DDS', 'DDS e Alinhamento de Turno', 'PROG', 1),
  (3, 'SETUP-MOLD', 'Setup / Troca de Molde e Ferramental', 'PROG', 1),
  (4, 'FALTA-MAT', 'Falta de Matéria-Prima / Insumos', 'NAO_PROG', 1),
  (5, 'FALHA-MEC', 'Falha Mecânica no Equipamento', 'NAO_PROG', 1),
  (6, 'FALHA-ELE', 'Falha Elétrica / Queda de Energia', 'NAO_PROG', 1),
  (7, 'AGUARD-ENG', 'Aguardando Liberação da Engenharia / Qualidade', 'NAO_PROG', 1),
  (8, 'ERRO-PROJ', 'Inconsistência no Desenho / Projeto', 'NAO_PROG', 1)
ON DUPLICATE KEY UPDATE `descricao` = VALUES(`descricao`), `tipo` = VALUES(`tipo`), `ativo` = VALUES(`ativo`);

-- ------------------------------------------------------------
-- 6. Cabeçalho de Turnos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `soma_turnos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `data` DATE NOT NULL,
  `turno` ENUM('D','N','M') NOT NULL DEFAULT 'D',
  `id_operador` INT UNSIGNED NOT NULL,
  `id_maquina` INT UNSIGNED NOT NULL,
  `id_setor` INT UNSIGNED NOT NULL,
  `id_empresa` INT UNSIGNED DEFAULT NULL,
  `h_inicio` TIME DEFAULT '07:00:00',
  `h_fim` TIME DEFAULT '17:00:00',
  `minutos_disponiveis` DECIMAL(8,2) NOT NULL DEFAULT 480.0,
  `minutos_produzidos` DECIMAL(8,2) NOT NULL DEFAULT 0.0,
  `minutos_paradas` DECIMAL(8,2) NOT NULL DEFAULT 0.0,
  `eficiencia` DECIMAL(6,2) NOT NULL DEFAULT 0.0,
  `status` VARCHAR(50) NOT NULL DEFAULT '[DENTRO DO PADRÃO]',
  `id_criador` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_soma_turnos_data` (`data`),
  KEY `idx_soma_turnos_turno` (`turno`),
  KEY `idx_soma_turnos_operador` (`id_operador`),
  KEY `idx_soma_turnos_maquina` (`id_maquina`),
  KEY `idx_soma_turnos_setor` (`id_setor`),
  KEY `idx_soma_turnos_empresa` (`id_empresa`),
  KEY `idx_soma_turnos_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_soma_turnos_operador` FOREIGN KEY (`id_operador`) REFERENCES `soma_operadores` (`id`),
  CONSTRAINT `fk_soma_turnos_maquina` FOREIGN KEY (`id_maquina`) REFERENCES `soma_maquinas` (`id`),
  CONSTRAINT `fk_soma_turnos_setor` FOREIGN KEY (`id_setor`) REFERENCES `soma_setores` (`id`),
  CONSTRAINT `fk_soma_turnos_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `soma_empresas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_soma_turnos_criador` FOREIGN KEY (`id_criador`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. Itens de Produção / Peças
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `soma_registros_producao` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_turno` INT UNSIGNED NOT NULL,
  `cod_peca` VARCHAR(50) NOT NULL,
  `descricao_peca` VARCHAR(150) DEFAULT NULL,
  `qtd` INT NOT NULL DEFAULT 1,
  `tp_padrao_min` DECIMAL(8,2) NOT NULL DEFAULT 0.0,
  `tp_total_produzido_min` DECIMAL(8,2) NOT NULL DEFAULT 0.0,
  `id_criador` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_soma_reg_prod_turno` (`id_turno`),
  KEY `idx_soma_reg_prod_peca` (`cod_peca`),
  KEY `idx_soma_reg_prod_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_soma_reg_prod_turno` FOREIGN KEY (`id_turno`) REFERENCES `soma_turnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_soma_reg_prod_criador` FOREIGN KEY (`id_criador`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. Itens de Paradas
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `soma_registros_paradas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_turno` INT UNSIGNED NOT NULL,
  `id_motivo` INT UNSIGNED NOT NULL,
  `duracao_minutos` DECIMAL(8,2) NOT NULL DEFAULT 0.0,
  `observacao` VARCHAR(255) DEFAULT NULL,
  `id_criador` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_soma_reg_paradas_turno` (`id_turno`),
  KEY `idx_soma_reg_paradas_motivo` (`id_motivo`),
  KEY `idx_soma_reg_paradas_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_soma_reg_paradas_turno` FOREIGN KEY (`id_turno`) REFERENCES `soma_turnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_soma_reg_paradas_motivo` FOREIGN KEY (`id_motivo`) REFERENCES `soma_paradas_motivos` (`id`),
  CONSTRAINT `fk_soma_reg_paradas_criador` FOREIGN KEY (`id_criador`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. Observações de Campo e Ergonomia
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `soma_observacoes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_turno` INT UNSIGNED NOT NULL,
  `texto` TEXT NOT NULL,
  `id_criador` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_soma_obs_turno` (`id_turno`),
  KEY `idx_soma_obs_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_soma_obs_turno` FOREIGN KEY (`id_turno`) REFERENCES `soma_turnos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_soma_obs_criador` FOREIGN KEY (`id_criador`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
