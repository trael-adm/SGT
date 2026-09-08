-- =============================================================================
-- Regras de validação de serigrafia/puncionamento por concessionária
-- (Paint Check — pages/qualidade/paint-check.php / api/paint-check-multi.php)
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. Regras por concessionária
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `concessionaria_regras` (
  `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome_grupo`               VARCHAR(120) NOT NULL,
  `norma_referencia`         VARCHAR(255) DEFAULT NULL,
  `incluir_ano_tampa`        TINYINT(1) NOT NULL DEFAULT 0,
  `formato_codigo_adicional` VARCHAR(100) DEFAULT NULL,
  `local_codigo_adicional`   SET('tampa','tanque') NOT NULL DEFAULT 'tanque',
  `locais_obrigatorios`      SET('tampa','tanque','gancho') NOT NULL DEFAULT 'tampa,tanque,gancho',
  `observacoes`              TEXT DEFAULT NULL,
  `ativo`                    TINYINT(1) NOT NULL DEFAULT 1,
  `id_criador`               INT DEFAULT NULL,
  `created_at`               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`                TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_concessionaria_regras_nome_grupo` (`nome_grupo`),
  KEY `idx_concessionaria_regras_ativo` (`ativo`),
  KEY `idx_concessionaria_regras_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_concessionaria_regras_criador` FOREIGN KEY (`id_criador`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Apelidos/variações de nome (como o cliente aparece no VSAT/planilha)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `concessionaria_regras_apelidos` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_regra`  INT UNSIGNED NOT NULL,
  `apelido`   VARCHAR(120) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_concessionaria_regras_apelidos_apelido` (`apelido`),
  KEY `idx_concessionaria_regras_apelidos_regra` (`id_regra`),
  CONSTRAINT `fk_concessionaria_regras_apelidos_regra` FOREIGN KEY (`id_regra`) REFERENCES `concessionaria_regras` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. Seed: padrão Particular/ABNT (fallback) + 9 concessionárias do pintura.md
-- ------------------------------------------------------------

-- PARTICULAR — fallback ABNT (NBR 5440/5356), sem código adicional formalizado
INSERT INTO `concessionaria_regras`
  (`nome_grupo`, `norma_referencia`, `incluir_ano_tampa`, `formato_codigo_adicional`, `local_codigo_adicional`, `locais_obrigatorios`, `observacoes`)
VALUES
  ('PARTICULAR', 'ABNT NBR 5440, ABNT NBR 5356', 0, NULL, 'tanque', 'tampa,gancho', 'Padrão de fallback (Mercado Particular/ABNT) — usado quando o cliente não bate com nenhum apelido cadastrado. Conforme pintura.md (tabela consolidada): sem puncionamento no tanque, apenas tampa + orelha de içamento (gancho) + parte ativa.')
ON DUPLICATE KEY UPDATE `norma_referencia` = VALUES(`norma_referencia`);

-- NEOENERGIA (Coelba, Cosern, Elektro, Neoenergia PE, Neoenergia Brasília)
-- Tampa leva NS + ano ("854208 2026"); Código do Material / Item do Pedido na tampa (20-30mm); tombamento no padrão "XXXXXX - Z"
INSERT INTO `concessionaria_regras`
  (`nome_grupo`, `norma_referencia`, `incluir_ano_tampa`, `formato_codigo_adicional`, `local_codigo_adicional`, `locais_obrigatorios`, `observacoes`)
VALUES
  ('Neoenergia', 'INS 72.30.05-2023, DIS-ETE-210, DIS-NOR-036', 1, '/^\\d{6}\\s*-\\s*\\d+$/', 'tampa,tanque', 'tampa,tanque,gancho', 'Confirmado (NS 850936, Cosern): DOIS códigos obrigatórios em locais diferentes — Código do Material/Item do Pedido na TAMPA (DIS-ETE-210 Item 6.1.27.4 e INS 72.30.05 Anexo B4 Item vii, sem formato fechado ainda) e tombamento vertical no TANQUE (Coelba XXXXXX.Z, PE/Cosern XXXXXXXX.Z, Elektro XXXXXX.F.Z, Brasília TRXXXX.ZX* com fundo vermelho — é esse que o formato_codigo_adicional valida). Puncionamento no tanque acima da placa confirmado.')
ON DUPLICATE KEY UPDATE `norma_referencia` = VALUES(`norma_referencia`), `incluir_ano_tampa` = VALUES(`incluir_ano_tampa`), `formato_codigo_adicional` = VALUES(`formato_codigo_adicional`), `local_codigo_adicional` = VALUES(`local_codigo_adicional`), `locais_obrigatorios` = VALUES(`locais_obrigatorios`), `observacoes` = VALUES(`observacoes`);

-- EQUATORIAL (MA, PA, PI, AL, RS, GO, AP) — "EQTL"
-- Patrimonio (num_serie_cliente) tem formato "0210021-027348-3", validado em modo puzzle
INSERT INTO `concessionaria_regras`
  (`nome_grupo`, `norma_referencia`, `incluir_ano_tampa`, `formato_codigo_adicional`, `local_codigo_adicional`, `locais_obrigatorios`, `observacoes`)
VALUES
  ('Equatorial', 'ET.00001.EQTL, ET.014.EQTL, NT.005.EQTL', 0, NULL, 'tampa,tanque', 'tampa,tanque,gancho', 'Confirmado: DOIS códigos em locais diferentes — Código do Material/Item Regional na TAMPA e Número do Patrimônio no TANQUE (face frontal, ET.00001.EQTL Item 7.1.3 b/c) — nenhum dos dois com formato fechado ainda. Puncionamento em 4 pontos (Tampa, Tanque acima da placa, Orelha de içamento e Parte Ativa - ET.00001.EQTL Item 7.1.3 c).')
ON DUPLICATE KEY UPDATE `norma_referencia` = VALUES(`norma_referencia`), `observacoes` = VALUES(`observacoes`), `local_codigo_adicional` = VALUES(`local_codigo_adicional`), `locais_obrigatorios` = VALUES(`locais_obrigatorios`);

-- CPFL Energia (Paulista, Piratininga, Santa Cruz, RGE)
INSERT INTO `concessionaria_regras`
  (`nome_grupo`, `norma_referencia`, `incluir_ano_tampa`, `formato_codigo_adicional`, `local_codigo_adicional`, `locais_obrigatorios`, `observacoes`)
VALUES
  ('CPFL', 'GED 196, GED 236, GED 3825, GED 17131', 0, '/^\\d{7}-\\d-\\d+$/', 'tanque', 'tampa,gancho', 'Número patrimonial CPFL formato xxxxxxx-y-z (60x50mm) na face frontal ou lateral esquerda (GED 196 Item 5.0). Puncionamento na tampa e orelha de içamento.')
ON DUPLICATE KEY UPDATE `norma_referencia` = VALUES(`norma_referencia`), `formato_codigo_adicional` = VALUES(`formato_codigo_adicional`), `locais_obrigatorios` = VALUES(`locais_obrigatorios`), `observacoes` = VALUES(`observacoes`);

-- COPEL (Distribuição - Paraná)
-- Confirmado (NS 856094): codigo "N Copel" (ex.: 5611533014) serigrafado no tanque
INSERT INTO `concessionaria_regras`
  (`nome_grupo`, `norma_referencia`, `incluir_ano_tampa`, `formato_codigo_adicional`, `local_codigo_adicional`, `locais_obrigatorios`, `observacoes`)
VALUES
  ('Copel', 'NTC 810027, NTC 811010, NTC 811040', 0, NULL, 'tanque', 'tampa,gancho', 'Confirmado (NS 856094): código N Copel no tanque. QR Code / DataMatrix na placa. Puncionamento de NS na tampa (ao lado de H1) e na orelha de içamento.')
ON DUPLICATE KEY UPDATE `norma_referencia` = VALUES(`norma_referencia`), `observacoes` = VALUES(`observacoes`), `locais_obrigatorios` = VALUES(`locais_obrigatorios`);

-- ENERGISA (MT, MS, PB, SE, TO, RO, AC, etc.)
INSERT INTO `concessionaria_regras`
  (`nome_grupo`, `norma_referencia`, `incluir_ano_tampa`, `formato_codigo_adicional`, `local_codigo_adicional`, `locais_obrigatorios`, `observacoes`)
VALUES
  ('Energisa', 'ETU-109.1, ETU-109.6, NDU-027', 0, NULL, 'tampa,tanque', 'tampa,tanque,gancho', 'Confirmado ETU-109.1 v4.2: Tampa leva H1..H3, Potência kVA e Número do Patrimônio. Fundo leva Potência kVA e Marca Energisa. Frente leva X0..X3, OPERAR SEM TENSÃO em Vermelho Munsell 5R 4/14 e Garantia. Traseira leva Patrimônio, Elo Fusível e Marca. Puncionamento no tanque acima da placa, tampa e alças de suspensão.')
ON DUPLICATE KEY UPDATE `norma_referencia` = VALUES(`norma_referencia`), `local_codigo_adicional` = VALUES(`local_codigo_adicional`), `locais_obrigatorios` = VALUES(`locais_obrigatorios`), `observacoes` = VALUES(`observacoes`);

-- CEMIG (Distribuição - Minas Gerais) — aparece no sistema como "Arrow"
INSERT INTO `concessionaria_regras`
  (`nome_grupo`, `norma_referencia`, `incluir_ano_tampa`, `formato_codigo_adicional`, `local_codigo_adicional`, `locais_obrigatorios`, `observacoes`)
VALUES
  ('CEMIG', '02.118-CEMIG-319u, 02.111-ED/CE-034', 0, '/^\\d{6}$/', 'tanque', 'tampa,gancho', 'Código SAP CEMIG de 6 dígitos numéricos pintado abaixo do patrimônio (>=30mm). OPERAR SEM TENSÃO no comutador. Puncionamento na placa, tampa/sobretampa e orelha de suspensão voltada para BT.')
ON DUPLICATE KEY UPDATE `norma_referencia` = VALUES(`norma_referencia`), `formato_codigo_adicional` = VALUES(`formato_codigo_adicional`), `locais_obrigatorios` = VALUES(`locais_obrigatorios`), `observacoes` = VALUES(`observacoes`);

-- CELESC (Distribuição - Santa Catarina)
INSERT INTO `concessionaria_regras`
  (`nome_grupo`, `norma_referencia`, `incluir_ano_tampa`, `formato_codigo_adicional`, `local_codigo_adicional`, `locais_obrigatorios`, `observacoes`)
VALUES
  ('Celesc', 'E-313.0019, E-141.0001', 0, NULL, 'tanque', 'tampa,gancho', 'Código Celesc D no tanque. OPERAR SEM TENSÃO junto ao manípulo. Puncionamento na tampa e orelha de içamento do tanque (E-313.0019 Item 5.18).')
ON DUPLICATE KEY UPDATE `norma_referencia` = VALUES(`norma_referencia`), `locais_obrigatorios` = VALUES(`locais_obrigatorios`), `observacoes` = VALUES(`observacoes`);

-- ENEL (São Paulo, Rio de Janeiro, Ceará)
INSERT INTO `concessionaria_regras`
  (`nome_grupo`, `norma_referencia`, `incluir_ano_tampa`, `formato_codigo_adicional`, `local_codigo_adicional`, `locais_obrigatorios`, `observacoes`)
VALUES
  ('Enel', 'Global Standard GST001, NTC-10, NTC-28', 0, NULL, 'tanque', 'tampa,gancho', 'DataMatrix 2D Enel (30x30mm). Terminais 1U..1W / 2U..2N ou H/X. OPERAR SEM TENSÃO no comutador. Puncionamento na borda da tampa e orelha de suspensão.')
ON DUPLICATE KEY UPDATE `norma_referencia` = VALUES(`norma_referencia`), `locais_obrigatorios` = VALUES(`locais_obrigatorios`), `observacoes` = VALUES(`observacoes`);

-- AMAZONAS ENERGIA
INSERT INTO `concessionaria_regras`
  (`nome_grupo`, `norma_referencia`, `incluir_ano_tampa`, `formato_codigo_adicional`, `local_codigo_adicional`, `locais_obrigatorios`, `observacoes`)
VALUES
  ('Amazonas Energia', 'FT400040, FT400073, FT400243, FT410122', 0, NULL, 'tanque', 'tampa,gancho', 'Logotipo Amazonas Energia, número do transformador e designação do elo fusível no tanque. Puncionamento na tampa e na orelha de suspensão.')
ON DUPLICATE KEY UPDATE `norma_referencia` = VALUES(`norma_referencia`), `locais_obrigatorios` = VALUES(`locais_obrigatorios`), `observacoes` = VALUES(`observacoes`);

-- ------------------------------------------------------------
-- 4. Seed: apelidos confirmados
-- ------------------------------------------------------------
INSERT INTO `concessionaria_regras_apelidos` (`id_regra`, `apelido`)
SELECT `id`, `apelido` FROM (
  SELECT 'Neoenergia' AS grupo, 'Coelba' AS apelido
  UNION ALL SELECT 'Neoenergia', 'Cosern'
  UNION ALL SELECT 'Neoenergia', 'Companhia Energética do Rio Grande do Norte Cosern'
  UNION ALL SELECT 'Neoenergia', 'Elektro'
  UNION ALL SELECT 'Neoenergia', 'Neoenergia PE'
  UNION ALL SELECT 'Neoenergia', 'Neoenergia Brasília'
  UNION ALL SELECT 'Neoenergia', 'Neoenergia'
  UNION ALL SELECT 'Equatorial', 'EQTL'
  UNION ALL SELECT 'Equatorial', 'Equatorial Para Distribuidora de Energia S.A'
  UNION ALL SELECT 'Equatorial', 'Equatorial'
  UNION ALL SELECT 'CEMIG', 'Arrow'
  UNION ALL SELECT 'CEMIG', 'Arrow Transportes e Logistica Ltda'
  UNION ALL SELECT 'CEMIG', 'CEMIG'
  UNION ALL SELECT 'Copel', 'Copel Distribuição S/A'
  UNION ALL SELECT 'Copel', 'Copel'
  UNION ALL SELECT 'Energisa', 'Energisa Mato Grosso - Distribuidora de Energia S.A'
  UNION ALL SELECT 'Energisa', 'Energisa'
) AS apelidos_seed
JOIN `concessionaria_regras` r ON r.`nome_grupo` = apelidos_seed.grupo
ON DUPLICATE KEY UPDATE `apelido` = VALUES(`apelido`);

SET FOREIGN_KEY_CHECKS = 1;
