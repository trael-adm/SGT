-- =============================================================================
-- Trael — SGT
-- Migração: "Materiais utilizados" da Triagem passa a buscar no catálogo real
-- (itens_catalogo, ver _inicial/migrar-itens-catalogo.sql) em vez do checklist
-- fixo `retrabalho_materiais_catalogo`. Troca a FK id_material por um snapshot
-- (codigo/descricao/unidade) gravado no momento da Triagem — mesma lógica já
-- usada em Produção (resolverTransformador()): não depender de uma referência
-- viva a um catálogo externo que pode ser reimportado com descrição diferente.
-- =============================================================================
-- Idempotente: pode ser rodado mais de uma vez sem erro (mesmo já aplicada).
-- Faz backfill de descricao/unidade das linhas antigas (ligadas ao checklist
-- fixo via id_material) ANTES de remover a FK/coluna, para não perder dado.
--
-- USO:
--   mysql -u root trael_db < _inicial/migrar-retrabalho-materiais-catalogo.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS _migrar_retra_mat_uso_patch;
DELIMITER $$
CREATE PROCEDURE _migrar_retra_mat_uso_patch()
BEGIN
    -- 1) Colunas novas (codigo/unidade) — adiciona se ainda não existirem.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retrabalho_material_uso' AND COLUMN_NAME = 'codigo'
    ) THEN
        ALTER TABLE retrabalho_material_uso ADD COLUMN codigo VARCHAR(20) NULL AFTER id_lote;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retrabalho_material_uso' AND COLUMN_NAME = 'unidade'
    ) THEN
        ALTER TABLE retrabalho_material_uso ADD COLUMN unidade VARCHAR(10) NULL;
    END IF;

    -- 2) Backfill: linhas antigas ligadas ao checklist fixo (id_material) recebem
    -- a descrição/unidade do catálogo antigo antes que a FK/coluna suma. codigo
    -- fica NULL para essas — o checklist fixo nunca teve código de produto real.
    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retrabalho_material_uso' AND COLUMN_NAME = 'id_material'
    ) THEN
        UPDATE retrabalho_material_uso mu
        JOIN retrabalho_materiais_catalogo mc ON mc.id = mu.id_material
        SET mu.material_outro = COALESCE(mu.material_outro, mc.descricao),
            mu.unidade        = mc.unidade
        WHERE mu.id_material IS NOT NULL;
    END IF;

    -- 3) Remove a FK e a coluna id_material.
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retrabalho_material_uso' AND CONSTRAINT_NAME = 'fk_retra_mat_uso_material'
    ) THEN
        ALTER TABLE retrabalho_material_uso DROP FOREIGN KEY fk_retra_mat_uso_material;
    END IF;
    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retrabalho_material_uso' AND COLUMN_NAME = 'id_material'
    ) THEN
        ALTER TABLE retrabalho_material_uso DROP COLUMN id_material;
    END IF;

    -- 4) Renomeia material_outro -> descricao (NOT NULL, mais larga pra caber
    -- descrições reais do catálogo). Linhas sem nada (não deveria sobrar
    -- nenhuma após o backfill do passo 2) recebem um rótulo genérico em vez de
    -- travar o NOT NULL.
    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retrabalho_material_uso' AND COLUMN_NAME = 'material_outro'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retrabalho_material_uso' AND COLUMN_NAME = 'descricao'
    ) THEN
        UPDATE retrabalho_material_uso SET material_outro = 'Material não identificado' WHERE material_outro IS NULL OR material_outro = '';
        ALTER TABLE retrabalho_material_uso CHANGE COLUMN material_outro descricao VARCHAR(255) NOT NULL;
    END IF;
END$$
DELIMITER ;
CALL _migrar_retra_mat_uso_patch();
DROP PROCEDURE _migrar_retra_mat_uso_patch;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Fim da migração. Confira com:
--   DESCRIBE retrabalho_material_uso;   -- id, id_lote, codigo, descricao, unidade, quantidade, id_criador, created_at
--   SELECT * FROM retrabalho_material_uso LIMIT 20;
-- =============================================================================
