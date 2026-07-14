-- =============================================================================
-- Trael — SGT
-- Migração: Retrabalho de transformadores (Etapa 1 — Banco)
-- =============================================================================
-- O QUE FAZ:
--   1. Cria as tabelas `pedidos`, `projetos` e `reprovas` (referência, já populada)
--   2. Limpa os registros de exemplo antigos de `retrabalhos`
--   3. Ajusta as colunas de `retrabalhos` para o novo modelo
--      (adiciona projeto/NS/reprova/datas; remove peça/turno/quantidade/motivo/projeto_op/data_ocorrencia)
--
-- USO (banco que JÁ existe):
--   mysql -u root trael_db < _inicial/migrar-retrabalho-transformadores.sql
--   ou importe pelo phpMyAdmin/HeidiSQL do Laragon.
--
-- ATENÇÃO: esta migração remove os retrabalhos existentes (decisão acordada:
-- limpar os exemplos antigos). Faça backup antes se houver dados que queira manter.
-- =============================================================================

USE trael_db;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─── 1. Pedidos ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pedidos (
    id         INT         AUTO_INCREMENT PRIMARY KEY,
    numero     VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP   NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP   NULL DEFAULT NULL,
    KEY idx_pedidos_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 2. Projetos ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS projetos (
    id         INT          AUTO_INCREMENT PRIMARY KEY,
    codigo     VARCHAR(50)  NOT NULL UNIQUE,
    descricao  VARCHAR(150) NULL,
    id_pedido  INT          NOT NULL,
    created_at TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP    NULL DEFAULT NULL,
    FOREIGN KEY (id_pedido) REFERENCES pedidos(id),
    KEY idx_projetos_pedido  (id_pedido),
    KEY idx_projetos_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 3. Reprovas / contenções (referência) ────────────────────────────────────
CREATE TABLE IF NOT EXISTS reprovas (
    id        INT          AUTO_INCREMENT PRIMARY KEY,
    codigo    VARCHAR(10)  NOT NULL UNIQUE,
    familia   VARCHAR(80)  NOT NULL,
    descricao VARCHAR(150) NOT NULL,
    local     ENUM('IQF','LAB','GER') NOT NULL,
    ativo     BOOLEAN      NOT NULL DEFAULT TRUE,
    ordem     INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO reprovas (codigo, familia, descricao, local, ordem) VALUES
    ('M1',  'ALÇA DE FIXAÇÃO PA', 'ALÇA DE FIXAÇÃO QUEBRADA',      'IQF',  1),
    ('M2',  'TERMINAL BT',        'TERMINAL DA BT QUEBRADO',       'IQF',  2),
    ('M3',  'COMUTADOR',          'COMUTADOR DERRETIDO',           'IQF',  3),
    ('M4',  'VAZAMENTO',          'VAZAMENTO NA AT',               'IQF',  4),
    ('M5',  'VAZAMENTO',          'VAZAMENTO NA BT',               'IQF',  5),
    ('M6',  'VAZAMENTO',          'VAZAMENTO NA TAMPA',            'IQF',  6),
    ('M7',  'VAZAMENTO',          'VAZAMENTO NO COMUTADOR',        'IQF',  7),
    ('M8',  'FURO NA SOLDA',      'FURO NO TANQUE',                'IQF',  8),
    ('M9',  'FURO NA SOLDA',      'FURO NA TAMPA',                 'IQF',  9),
    ('M10', 'FURO NA SOLDA',      'FURO NO RADIADOR',              'IQF', 10),
    ('M11', 'BT',                 'TERMINAL BT TROCADO',           'IQF', 11),
    ('E1',  'REPROVA ELÉTRICA',   'COR. EXCITAÇÃO FORA DE NORMA',  'LAB', 12),
    ('E2',  'REPROVA ELÉTRICA',   'PERDAS A VAZIO FORA DE NORMA',  'LAB', 13),
    ('E3',  'REPROVA ELÉTRICA',   'IMPEDANCIA FORA DE NORMA',      'LAB', 14),
    ('E4',  'REPROVA ELÉTRICA',   'PERDAS TOTAIS FORA DE NORMA',   'LAB', 15),
    ('E5',  'REPROVA ELÉTRICA',   'FIO ROMPIDO AT',                'LAB', 16),
    ('E6',  'REPROVA ELÉTRICA',   'FIO ROMPIDO BT',                'LAB', 17),
    ('E7',  'REPROVA ELÉTRICA',   'APLICADA AT',                   'LAB', 18),
    ('E8',  'REPROVA ELÉTRICA',   'APLICADA BT',                   'LAB', 19),
    ('E9',  'REPROVA ELÉTRICA',   'INDUZIDA',                      'LAB', 20),
    ('E10', 'REPROVA ELÉTRICA',   'INDUZIDA CURTO DIRETO',         'LAB', 21),
    ('R1',  'REVITALIZAÇÃO',      'REVITALIZAÇÃO',                 'GER', 22);

-- ─── 4. Limpar retrabalhos antigos (decisão: limpar exemplos) ─────────────────
DELETE FROM retrabalhos;
ALTER TABLE retrabalhos AUTO_INCREMENT = 1;

-- ─── 5. Novas colunas de retrabalhos ──────────────────────────────────────────
ALTER TABLE retrabalhos
    ADD COLUMN id_projeto       INT         NULL AFTER operador,
    ADD COLUMN ns_transformador VARCHAR(60) NULL AFTER id_projeto,
    ADD COLUMN id_reprova       INT         NULL AFTER ns_transformador,
    ADD COLUMN data_reprova     DATE        NULL AFTER id_reprova,
    ADD COLUMN data_inicio      DATE        NULL AFTER data_reprova,
    ADD COLUMN data_fim         DATE        NULL AFTER data_inicio;

-- ─── 6. Remover colunas do modelo antigo ──────────────────────────────────────
ALTER TABLE retrabalhos
    DROP COLUMN peca,
    DROP COLUMN turno,
    DROP COLUMN quantidade,
    DROP COLUMN motivo,
    DROP COLUMN projeto_op,
    DROP COLUMN data_ocorrencia;

-- ─── 7. Chaves estrangeiras e índices dos novos campos ────────────────────────
ALTER TABLE retrabalhos
    ADD CONSTRAINT fk_retra_projeto FOREIGN KEY (id_projeto) REFERENCES projetos(id),
    ADD CONSTRAINT fk_retra_reprova FOREIGN KEY (id_reprova) REFERENCES reprovas(id),
    ADD KEY idx_retra_projeto (id_projeto),
    ADD KEY idx_retra_reprova (id_reprova),
    ADD KEY idx_retra_ns      (ns_transformador);

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Fim da migração. Confira com:
--   SHOW TABLES;                 -- deve listar pedidos, projetos, reprovas
--   SELECT COUNT(*) FROM reprovas;   -- deve retornar 22
--   DESCRIBE retrabalhos;        -- deve mostrar id_projeto, ns_transformador, id_reprova, data_reprova, data_inicio, data_fim
-- =============================================================================
