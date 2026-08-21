-- ========================================================
-- Migração: Adiciona coluna setor_causador na tabela reprovas
-- e atualiza classificação conforme prefixos padronizados
-- ========================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- 1. Adicionar coluna setor_causador se não existir
SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'reprovas' 
      AND COLUMN_NAME = 'setor_causador'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE reprovas ADD COLUMN setor_causador VARCHAR(50) NOT NULL DEFAULT \'S/ Setor Causador\' AFTER codigo',
    'SELECT "Coluna setor_causador ja existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Atualizar setor causador baseado nos prefixos oficiais
UPDATE reprovas SET setor_causador = 'ENGENHARIA' WHERE codigo REGEXP '^EG[0-9]+';
UPDATE reprovas SET setor_causador = 'CALDEIRARIA' WHERE codigo REGEXP '^C[0-9]+';
UPDATE reprovas SET setor_causador = 'ELÉTRICO'    WHERE codigo REGEXP '^E[0-9]+';
UPDATE reprovas SET setor_causador = 'LINHA'       WHERE codigo REGEXP '^L[0-9]+';
UPDATE reprovas SET setor_causador = 'PINTURA'     WHERE codigo REGEXP '^P[0-9]+';
UPDATE reprovas SET setor_causador = 'REVITALIZAÇÃO' WHERE codigo REGEXP '^R[0-9]+';

-- Qualquer código que não tenha prefixo identificado ou seja M1..M11 fica com S/ Setor Causador
UPDATE reprovas SET setor_causador = 'S/ Setor Causador' 
WHERE setor_causador IS NULL 
   OR setor_causador = '' 
   OR (codigo NOT REGEXP '^(EG|C|E|L|P|R)[0-9]+');

-- 3. Inserir reprovas novas que estão na planilha mas não estavam cadastradas
INSERT INTO reprovas (codigo, setor_causador, familia, descricao, local, vai_retrabalho, ordem, ativo)
SELECT 'L37', 'LINHA', 'TERMINAL', 'TERMINAL BT ERRADO', 'IQF', 0, 150, 1
WHERE NOT EXISTS (SELECT 1 FROM reprovas WHERE codigo = 'L37');

INSERT INTO reprovas (codigo, setor_causador, familia, descricao, local, vai_retrabalho, ordem, ativo)
SELECT 'P39', 'PINTURA', 'SERIGRAFIA', 'SERIGRAFIA FALHADA', 'IQF', 0, 151, 1
WHERE NOT EXISTS (SELECT 1 FROM reprovas WHERE codigo = 'P39');

INSERT INTO reprovas (codigo, setor_causador, familia, descricao, local, vai_retrabalho, ordem, ativo)
SELECT 'P40', 'PINTURA', 'PINTURA', 'PINTURA DANIFICADA/BATIDA PRÓXIMA DA BT', 'IQF', 0, 152, 1
WHERE NOT EXISTS (SELECT 1 FROM reprovas WHERE codigo = 'P40');

INSERT INTO reprovas (codigo, setor_causador, familia, descricao, local, vai_retrabalho, ordem, ativo)
SELECT 'P41', 'PINTURA', 'PINTURA', 'PINTURA DANIFICADA/BATIDA NA PRESILHA', 'IQF', 0, 153, 1
WHERE NOT EXISTS (SELECT 1 FROM reprovas WHERE codigo = 'P41');

INSERT INTO reprovas (codigo, setor_causador, familia, descricao, local, vai_retrabalho, ordem, ativo)
SELECT 'P42', 'PINTURA', 'PINTURA', 'PINTURA DANIFICADA/BATIDA NA ALÇA', 'IQF', 0, 154, 1
WHERE NOT EXISTS (SELECT 1 FROM reprovas WHERE codigo = 'P42');

INSERT INTO reprovas (codigo, setor_causador, familia, descricao, local, vai_retrabalho, ordem, ativo)
SELECT 'P43', 'PINTURA', 'PINTURA', 'PINTURA TRINCA/FISSURA DE TINTA RADIADOR', 'IQF', 0, 155, 1
WHERE NOT EXISTS (SELECT 1 FROM reprovas WHERE codigo = 'P43');
